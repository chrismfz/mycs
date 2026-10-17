/*
***********************************************************************************************
                   Foundation for Research and Technology Hellas
                       Institute of Computer Science

                              [.gr] ccTLD

                       EPP Client Application v1.0

 Authors: Nikos Papadopoulos 
          Ilias Theocharopoulos

This application is provided as is. 
FORTH/ICS does not have any responsibility if this application does not work the 
way you expect it to do.

You may not distribute or use this application for commercial purposes. 
This application may be used freely as a reference EPP Client implementation 
for [.gr] ccTLD registrars, provided that this notice is kept intact.
                            
***********************************************************************************************
*/

import java.net.*;
import java.io.*;
import java.util.*;

public class EppClient{
	/*
	*  Method main requires two parameters. The first one is the filename of the file with
	*  the epp command. The second (if provided) is the cookie to be used, which denotes
	*  the session id, which the server requires
	*/
	public static void main(String argv[]){
		String cookie = null;
		String serverCookie = null;

		try{
			/* 'TrustStore' file denotes that the server we connect to is trusted */
			System.setProperty("javax.net.ssl.trustStore","TrustStore");

			/* connecting to the server... */

			/* the address of the epp server */
			String address = "https://uat-regepp.ics.forth.gr:700/epp/proxy";
			URL url = new URL(address); 
			HttpURLConnection c = (HttpURLConnection)url.openConnection();
			c.setRequestProperty("Content-Type","text/xml;charset=UTF-8");
			c.setDoOutput(true);
			/* the second parameter (if provided) denotes the cookie [session] to be used */
			if (argv.length == 2) cookie = argv[1];

			/*
			*  If we do not provide second parameter (case of 'hello' or 'login'
			*  epp command) a new cookie will be received and printed to the output. 
			*********************************************************************
			*  This cookie must be copied, saved and passed as the second
			*  parameter to subsequent calls of this client
			*********************************************************************
			*/
			if (cookie != null) c.setRequestProperty("Cookie",cookie);
			else{
			/* new cookie [session] will be received from the server */
				OutputStream cOut1 = c.getOutputStream();
				cOut1.write('\n');
				cOut1.flush();
				String servCook = c.getHeaderField("Set-Cookie");

				/*
				*  the cookie might have various values seperated by a ';'.
				*  The first of them is the session id we want
				*/
				if (servCook != null){
					cookie = servCook.split(";")[0];
					System.out.println("Cookie:"+cookie);
				}
			}

			/* the first parameter is the filename of the file with the epp command */
			FileInputStream eppIn = new FileInputStream(argv[0]);
			byte[] bytes = new byte[eppIn.available()];

			/* read the epp command */
			eppIn.read(bytes);

			c.disconnect();
			c = (HttpURLConnection)url.openConnection();
			c.setRequestProperty("Content-Type","text/xml;charset=UTF-8");

			/*
			*  set the cookie [session] we have 
			*  (the second parameter or the one we got from the server)
			*/
			c.setRequestProperty("Cookie",cookie);

			c.setRequestProperty("Content-Length",String.valueOf(bytes.length));
			c.setDoOutput(true);
			OutputStream cOut = c.getOutputStream();

			/* we send the epp command through the https channel to the server */
			cOut.write(bytes);
			cOut.close();

			/* get the answer to the epp command */
			InputStream in = c.getInputStream();
			InputStreamReader ir = new InputStreamReader(in,"UTF-8");
			BufferedReader br = new BufferedReader(ir);

			String line = null;

			/* print the answer */
			while( (line=br.readLine()) != null ){
				System.out.println(line);
			}
		}catch(Exception Ex){
			System.err.println("EppClient Error:"+Ex.getMessage());
		}
	}
}
